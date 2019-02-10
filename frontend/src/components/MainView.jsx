import React from 'react';
import PropTypes from 'prop-types';
import { withStyles } from '@material-ui/core/styles';
import classnames from 'classnames';
import AppBar from '@material-ui/core/AppBar';
import Toolbar from '@material-ui/core/Toolbar';
import Typography from '@material-ui/core/Typography';
import Button from '@material-ui/core/Button';
import ExpandLess from '@material-ui/icons/ExpandLess';
import MainMenu from './MainMenu';
import Parameters from './parameters/Parameters';
import SearchResult from "./SearchResult";
import ArrowBackIcon from '@material-ui/icons/ArrowBack';
import InputIcon from '@material-ui/icons/Input';
import { fade } from '@material-ui/core/styles/colorManipulator';
import Select from '@material-ui/core/Select';
import MenuItem from '@material-ui/core/MenuItem';
import {compose} from "recompose";
import {withGlobalCommands} from "./GlobalCommands";

const drawerHeight = 220;
const drawerPaddingTop = 24;

const styles = theme =>({
  root: {
    display: 'flex',
    flexDirection: 'column',
    height: '100%',
    boxSizing: 'border-box'
  },
  appBar: {
    background: '#f4f4f4',// TODO change according to standalone mode - 'rgb(60, 66, 82)'
    color: 'black !important'
  },
  grow: {
    flexGrow: 1
  },
  menuButton: {
    marginLeft: -12,
    marginRight: 20,
  },
  parameters: {
    height: drawerHeight,
    background: '#f5f5f5',
    boxShadow: '0px 2px 4px -1px rgba(0, 0, 0, 0.2), 0px 4px 5px 0px rgba(0, 0, 0, 0.14), 0px 1px 10px 0px rgba(0, 0, 0, 0.12)',
    color: 'rgba(0, 0, 0, 0.87)',
    padding: `12px ${drawerPaddingTop}px`,
    // transition: 'all 700ms'
  },
  parametersShift: {
    transform: `translateY(-${drawerHeight + drawerPaddingTop}px)`
  },
  content : {
    display: 'flex',
    flexDirection: 'column',
    flexGrow: 1,
    marginTop: -drawerHeight - drawerPaddingTop,
    //transition: 'margin 700ms'
  },
  contentShift: {
    marginTop: 0
  },
  result: {
    display: 'flex',
    flexGrow: 1
  },
  loadingModal: {
    display: 'flex',
    flexDirection: 'column',
    justifyContent: 'center',
    alignItems: 'center',
    position: 'absolute',
    width: '100px',
    outline: 'none',
    background: 'rgba(255, 255, 255, 0.9)',
    borderRadius: '16px',
    padding: '10px',
    boxShadow: '0px 2px 4px -1px rgba(0, 0, 0, 0.2), 0px 4px 5px 0px rgba(0, 0, 0, 0.14), 0px 1px 10px 0px rgba(0, 0, 0, 0.12)',
    top: '50%',
    left: '50%',
    transform: 'translate(-50%, -50%)',
    '& > .marginTop' : {
      marginTop: '10px'
    }
  },
  search: {
    position: 'relative',
    borderRadius: theme.shape.borderRadius,
    backgroundColor: fade(theme.palette.common.white, 0),
    '&:hover': {
      backgroundColor: fade(theme.palette.common.white, 0.3),
    },
    marginRight: theme.spacing.unit * 2,
    marginLeft: 0,
    width: '100%',
    [theme.breakpoints.up('sm')]: {
      marginLeft: theme.spacing.unit * 3,
      width: 'auto',
    },
  },
  searchIcon: {
    width: theme.spacing.unit * 9,
    height: '100%',
    position: 'absolute',
    pointerEvents: 'none',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
  },
  alignCenter: {
    textAlign: 'center'
  },
  inputRoot: {
    color: 'inherit',
    width: '100%',
  },
  inputInput: {
    paddingTop: theme.spacing.unit,
    paddingRight: theme.spacing.unit,
    paddingBottom: theme.spacing.unit,
    paddingLeft: theme.spacing.unit * 10,
    transition: theme.transitions.create('width'),
    width: '100%',
    [theme.breakpoints.up('md')]: {
      width: 200,
    },
  },
  leftIcon: {
    marginRight: theme.spacing.unit,
  },
  button: {
    margin: theme.spacing.unit,
  },
  title: {
    marginRight: theme.spacing.unit,
  }
});


class MainView extends React.Component {

  state = {
    expanded: true,
    canCollapse: false,
    parameters: null,
    timeZone: 'est',
    searchStack: []
  }

  _showParameters = () => {
    this.setState(state => ({expanded: true}));
  }

  _hideParameters = () => {
    this.setState(state => ({expanded: false}));

  }

  _abortSearch = () => {
    this.setState({
      parameters: null,
      canCollapse: false,
      expanded: true
    })
  }

  _handleSearch = (parameters, addToStack = true) => {

    this.setState((state) => {
      return {
        searchStack: addToStack && state.parameters ? [ ...state.searchStack, state.parameters] : state.searchStack,
        parameters: null
      };
    }, (state) => {

      this.setState({

        canCollapse: true,
        expanded: false,
        parameters
      })
    })
  }

  _handleGoBack = () => {

    this.setState((state) => {
      const { searchStack } = this.state;

      if (searchStack.length === 0) {
        return;
      }

      const parameters = searchStack.pop();
      this._handleSearch(parameters, false);

      return {
        searchStack
      };
    }
    )

  }

  handleChangeTimeZone = (e) => {
    this.props.globalCommands.changeTimezone(e.target.value);
  }

  render() {
    const {classes, globalCommands } = this.props;
    const {expanded, canCollapse, parameters, searchStack } = this.state;

    const inSearch = !!parameters;
    const hasBack = searchStack.length > 0;

    return (
      <div className={classes.root}>
        <AppBar position="relative" classes={{root: classes.appBar}}>
          <Toolbar>
            <Typography variant="h6" color="inherit" className={classes.title} >
              Kelloggs!
            </Typography>

            {hasBack &&
              <Button className={classes.button} onClick={this._handleGoBack}>
                <ArrowBackIcon className={classes.leftIcon}/>
                Back
              </Button>
              }
            { inSearch &&
            < Button  className={classes.button} onClick={this._showParameters}>
              <InputIcon className={classes.leftIcon} />
              Modify Search
            </Button>
            }

            <div className={classes.grow} />
            {/*<div className={classes.search}>*/}
              {/*<div className={classes.searchIcon}>*/}
                {/*<SearchIcon />*/}
              {/*</div>*/}
              {/*<InputBase*/}
                {/*placeholder="Search…"*/}
                {/*classes={{*/}
                  {/*root: classes.inputRoot,*/}
                  {/*input: classes.inputInput,*/}
                {/*}}*/}
              {/*/>*/}
            {/*</div>*/}
            <Select
              value={globalCommands.timezone}
              disabled={true}
              onChange={this.handleChangeTimeZone}
              inputProps={{
                name: 'timezone',
              }}
            >
              <MenuItem value={'EST'}>EST Time</MenuItem>
              <MenuItem value={'GMT'}>GMT Time</MenuItem>
              <MenuItem value={'LOCAL'}>Local Time</MenuItem>
            </Select>
            <MainMenu />
          </Toolbar>
        </AppBar>
        <div className={classnames(classes.parameters, !expanded && classes.parametersShift)}>
          <Parameters onSearch={this._handleSearch}></Parameters>
        </div>
        <div className={classnames(classes.content, expanded && classes.contentShift)}>
          {expanded && canCollapse && <Button onClick={this._hideParameters} className={classes.toggler}>
            <React.Fragment>
              <ExpandLess fontSize="small"/>
              Hide Parameters
            </React.Fragment>
          </Button>
          }
          <div className={classes.result}>
            {parameters &&
            <SearchResult  parameters={parameters} onClose={this._abortSearch}/>}
          </div>
        </div>
      </div>
    )
  }
}


MainView.propTypes = {
  classes: PropTypes.object.isRequired,
};

export default compose(
  withStyles(styles),
  withGlobalCommands
)(MainView);


