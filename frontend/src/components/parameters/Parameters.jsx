import * as React from 'react';
import Grid from '@material-ui/core/Grid';
import Input from '@material-ui/core/Input';
import InputLabel from '@material-ui/core/InputLabel';
import MenuItem from '@material-ui/core/MenuItem';
import FormControl from '@material-ui/core/FormControl';
import Typography from '@material-ui/core/Typography';
import Select from '@material-ui/core/Select';
import Button from '@material-ui/core/Button';
import {withStyles} from "@material-ui/core";
import APILogsParameters from './APILogsParameters';
import moment from 'moment';

const styles = {
  parametersForm: {
    padding: '12px',
  },
  fields: {
  }
}

class Parameters extends React.Component
{
  apiLogsRef = React.createRef();

  static defaultProps = {

  }

  state = {
    parameters: {
      type: "",
      textFilter: "",
      fromTime: moment().add(-1, 'days').startOf('day').format('YYYY-MM-DD HH:mm'),
      toTime: moment().add(-1, 'days').endOf('day').format('YYYY-MM-DD HH:mm'),
      server: "",
      session: ""
    }
  }

  _handleChange = (e) => {
    const { name, value } = e.target;
    this.setState(state => {
      return (
        {
          parameters: {
            ...state.parameters,
            [name]: value
          }
        }
      )
    })
  }

  toStringDate = (dateAsNumber) => {
  return moment(dateAsNumber * 1000).format('YYYY-MM-DD HH:mm');
}

  toUnixDate = (date) => {
    const parsedDate = moment(date);
    return parsedDate.isValid() ? parsedDate.format('X') : ""
  }

  validateDate = (date) => {
    if (!date) {
      return false;
    }

    return moment(date).isValid();
  }

  _handleSearch = () => {

    const {parameters: rawParameters} = this.state;
    const {onSearch} = this.props;

    let isValid = false;
    if (rawParameters.type === 'apiLogFilter') {
      if (this.apiLogsRef.current) {
        isValid = this.apiLogsRef.current.validate();
      }
    }

    if (!isValid) {
      return;
    }
    const searchParameters =
      {
        ...rawParameters,
        fromTime: this.toUnixDate(rawParameters.fromTime),
        toTime: this.toUnixDate(rawParameters.toTime),
        textFilter: rawParameters.textFilter ? {type: 'match', text: rawParameters.textFilter} : undefined
      };

    onSearch(searchParameters)
  }

  render() {
    const { parameters } = this.state;
    const { classes, onSearch } = this.props;

    return (
      <div style={{width: '100%', height: '100%'}}>
      <Grid  container spacing={16} alignItems={'flex-end'}>
        <Grid item xs={4}>
          <Typography variant={'headline'}>Search parameters</Typography>
        </Grid>
          <Grid item xs={4}>
            <FormControl fullWidth>
              <InputLabel shrink htmlFor="type-input">
                Select search type
              </InputLabel>
              <Select
                value={parameters.type}
                onChange={this._handleChange}
                input={<Input name="type" id="type-input" />}
                displayEmpty
                name="type"
              >
                <MenuItem value="">
                  <em>Select...</em>
                </MenuItem>
                <MenuItem value={'apiLogFilter'}>API Logs</MenuItem>
                <MenuItem value={'databaseChanges'}>Database changes</MenuItem>
                <MenuItem value={'batchJobs'}>Batch jobs</MenuItem>
              </Select>
            </FormControl>
          </Grid>
          <Grid item xs={4}>
            <Button disabled={!parameters.type} variant="contained" style={{float: 'right'}} onClick={this._handleSearch}>
              Search
            </Button>
          </Grid>
        <Grid item xs={12}>
          { parameters.type === 'apiLogFilter' && <APILogsParameters ref={this.apiLogsRef} {...this.state.parameters} onChange={this._handleChange} className={classes.parametersForm}></APILogsParameters> }
        </Grid>
      </Grid>
      <div style={{background: 'rgba(0, 0, 0, 0.5)'}}></div>
    </div>
    )
  }
}

export default withStyles(styles)(Parameters);
