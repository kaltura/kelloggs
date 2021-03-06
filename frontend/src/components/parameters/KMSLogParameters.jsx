import React from 'react';
import Grid from "@material-ui/core/Grid/Grid";
import TextField from "@material-ui/core/TextField/TextField";
import Paper from "@material-ui/core/Paper/Paper";
import moment from 'moment';
import ClearableTextField from '../ClearableTextField';
import FormControl from "@material-ui/core/FormControl/FormControl";
import InputLabel from "@material-ui/core/InputLabel/InputLabel";
import Select from "@material-ui/core/Select/Select";
import Input from "@material-ui/core/Input/Input";
import MenuItem from "@material-ui/core/MenuItem/MenuItem";
import {pick} from "ramda";

const defaultParams = ['type', 'fromTime', 'toTime', 'textFilter', 'session', 'server', 'logTypes'];

export default class KMSLogParameters extends React.Component {
  state = {
    isFromTimeValid: true,
    isToTimeValid: true
  };

  filterParameters = pick(defaultParams);

  componentDidMount() {
    const { onChange } = this.props;

    if (['kms','kmsFront'].indexOf(this.props.logTypes) !== -1) {
      return;
    }

    onChange({ target : { name: 'logTypes', value: 'kms'}});
  }

  validate = () => {
    const isFromTimeValid = this._validateDate('fromTime', 'isFromTimeValid');
    const isToTimeValid = this._validateDate('toTime', 'isToTimeValid');
    return isFromTimeValid && isToTimeValid;
  }

  _validateDate = (propertyName, validStateName) => {
    const value = this.props[propertyName];
    const isValid = (value && moment(value).isValid());
    this.setState({
      [validStateName]: isValid
    })

    return isValid;
  }

  render() {
    const { textFilter, session, server, fromTime, toTime, onChange, className: classNameProp, onTextFilterChange, onClear, logTypes } = this.props;
    const { isFromTimeValid, isToTimeValid } = this.state;

    return (
      <Paper elevation={1} className={classNameProp}>
        <Grid container spacing={16} >
          <Grid item xs={4}>
            <TextField fullWidth
                       error={!isFromTimeValid}
                       name="fromTime"
                       label="From Time"
                       value={fromTime}
                       onChange={onChange}
                       helperText={!isFromTimeValid && "Date is missing or invalid"}
                       onBlur={() => this._validateDate('fromTime', 'isFromTimeValid')}
                       InputLabelProps={{
                         shrink: true,
                       }}
            />
          </Grid>
          <Grid item xs={4}>
            <TextField fullWidth
                       error={!isToTimeValid}
                       name="toTime"
                       label="To Time"
                       value={toTime}
                       onChange={onChange}
                       helperText={!isToTimeValid && "Date is missing or invalid"}
                       onBlur={() => this._validateDate('toTime', 'isToTimeValid')}
                       InputLabelProps={{
                         shrink: true,
                       }}
            />
          </Grid>
          <Grid item xs={4}>
            <ClearableTextField
              fullWidth
              onClear={() => onTextFilterChange("")}
              label="Search Criteria"
              name='textFilter'
              value={textFilter.text}
              onChange={(e) => onTextFilterChange(e.target.value)}
            />
          </Grid>
          <Grid item xs={4}>
              <ClearableTextField fullWidth
                onClear={onClear}
              name="server"
              label="Server"
              value={server}
              onChange={onChange}
              InputLabelProps={{
                shrink: true,
          }}/>
          </Grid>
          <Grid item xs={4}>
            <ClearableTextField
              fullWidth
              name="session"
              label="Session"
              value={session}
              onClear={onClear}
              onChange={onChange}
              InputLabelProps={{
                shrink: true,
              }}
            />
          </Grid>
          <Grid item xs={4}>
            <FormControl
              fullWidth
            >
              <InputLabel>Log Types</InputLabel>
              <Select
                value={logTypes}
                onChange={onChange}
                input={<Input name="logTypes" id="type-input" />}
              >
                <MenuItem value={'kms'}>KMS</MenuItem>
                <MenuItem value={'kmsFront'}>KMS Front</MenuItem>
              </Select>
            </FormControl>
          </Grid>
        </Grid>
      </Paper>
    )
  }
}