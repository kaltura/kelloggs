import React from 'react';
import PropTypes from 'prop-types';
import { withStyles } from '@material-ui/core/styles';
import classnames from 'classnames';
import AppBar from '@material-ui/core/AppBar';
import Toolbar from '@material-ui/core/Toolbar';
import Typography from '@material-ui/core/Typography';
import Button from '@material-ui/core/Button';
import ExpandMore from '@material-ui/icons/ExpandMore';
import ExpandLess from '@material-ui/icons/ExpandLess';
import MainMenu from './MainMenu';
import Parameters from './parameters/Parameters';
import SearchResult from "./SearchResult";

const drawerHeight = 220;
const drawerPaddingTop = 24;

const styles = {
  root: {
    display: 'flex',
    flexDirection: 'column',
    height: '100%',
    boxSizing: 'border-box'
  },
  appBar: {
    background: 'rgb(60, 66, 82)'
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
  alignCenter: {
    textAlign: 'center'
  }
};


class MainView extends React.Component {

  state = {
    expanded: true,
    canCollapse: false,
    parameters: null,
  }

  _toggleOpen = () => {
    this.setState(state => ({expanded: !state.expanded}));
  }

  _abortSearch = () => {
    this.setState({
      parameters: null,
      canCollapse: false,
      expanded: true
    })
  }

  _handleSearch = (parameters) => {
    this.setState({
      parameters: null
    }, () => {
      this.setState({
        canCollapse: true,
        expanded: false,
        parameters
      })
    })

  }

  render() {
    const {classes, globalCommands } = this.props;
    const {expanded, canCollapse, parameters} = this.state;

    return (
      <div className={classes.root}>
        <AppBar position="relative" classes={{root: classes.appBar}}>
          <Toolbar>
            <Typography variant="h6" color="inherit" className={classes.grow}>
              Kelloggs!
            </Typography>
            <MainMenu/>
          </Toolbar>
        </AppBar>
        <div className={classnames(classes.parameters, !expanded && classes.parametersShift)}>
          <Parameters onSearch={this._handleSearch}></Parameters>
        </div>
        <div className={classnames(classes.content, expanded && classes.contentShift)}>
          {canCollapse && <Button onClick={this._toggleOpen} className={classes.toggler}>
            {expanded ? (
                <React.Fragment>
                  <ExpandLess fontSize="small"/>
                  Hide Search Parameters
                </React.Fragment>
              )
              :
              (
                <React.Fragment>
                  <ExpandMore fontSize="small"/>
                  Modify Search Parameters
                </React.Fragment>
              )
            }
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


export default withStyles(styles)(MainView);
